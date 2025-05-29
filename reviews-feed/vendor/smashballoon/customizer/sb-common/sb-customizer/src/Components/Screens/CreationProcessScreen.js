import { useState, useMemo, useContext } from 'react'
import SbUtils from '../../Utils/SbUtils'
import { __ } from '@wordpress/i18n'
import SelectSourcesSection from './CreationProcess/SelectSourcesSection'
import SelectTemplateSection from './CreationProcess/SelectTemplateSection'
import Button from '../Common/Button'
import FullScreenLoader from '../Global/FullScreenLoader'
import DashboardScreenContext from '../Context/DashboardScreenContext'

const CreationProcessScreen = ( props ) => {

    const {
        sbCustomizer,
        sources,
        fbModal,
        isPro
    } = useContext( DashboardScreenContext );
    const [ creationStep , setCreationStep ] = useState( 'selectSource' );


    const setInitialSelectSources = () => {
        let initialSources = [];
        if( sources?.sourcesList.length === 1 ){
            initialSources.push(sources?.sourcesList[0].account_id);
        }
        if( SbUtils.checkNotEmpty( localStorage.getItem('newAddedSourceId') ) ){
            const newAddedSourceId = localStorage.getItem('newAddedSourceId');
            initialSources.push(newAddedSourceId)
            localStorage.removeItem('newAddedSourceId');
        }
        return initialSources;
    }

    const initialSelectedSource = sources?.sourcesList.length === 1 ? [sources?.sourcesList[0].account_id] : [];
    const [ selectedSources, setSelectedSources ] = useState( initialSelectedSource );
    if( SbUtils.checkNotEmpty( localStorage.getItem('newAddedSourceId') ) ){
            const newAddedSourceId = localStorage.getItem('newAddedSourceId'),
                newASelectedSource = [selectedSources];

            newASelectedSource.push(newAddedSourceId);
            setTimeout(() => {
                setSelectedSources([...newASelectedSource])
            }, 100);
            localStorage.removeItem('newAddedSourceId');
        }

    const [ selectedTemplate, setSelectedTemplate ] = useState( 'default' );
    const [ submitFeed, setSubmitFeed ] = useState( false );

    let steps = {
        'selectSource' : {
            'heading'       : __( 'Add Source', 'sb-customizer' ) ,
            'description'   : __( 'Select one or more sources you would like to add', 'sb-customizer' ),
            'condition'     : Object.values( selectedSources ).length > 0
        },
        'selectTemplate' : {
            'heading' : __( 'Select a Template', 'sb-customizer' ) ,
            'description' : __( 'Select one or more sources you would like to add', 'sb-customizer' ),
            'condition'     : selectedTemplate !== null && selectedTemplate !== false
        }
    };
    const submitNewFeed = () => {
        setSubmitFeed( true )
        let firstSelectedSource = selectedSources[0].length === 0 && selectedSources[1] !== undefined ? selectedSources[1] : selectedSources[0]

        const feedName = sources?.sourcesList?.filter( sr => sr.account_id === firstSelectedSource);

        const formData = {
            action : 'sbr_feed_saver_manager_builder_update',
            new_insert : true,
            feedTemplate : selectedTemplate,
            sources : JSON.stringify(selectedSources)
        };
        if( feedName[0] ){
            formData.feed_name = feedName[0]?.name
        }

        SbUtils.ajaxPost(
            sbCustomizer.ajaxHandler,
            formData,
            ( data ) => { //Call Back Function
                localStorage.setItem('newCreatedFeed', data.feed_id);
                window.location.href = sbCustomizer.builderUrl + '&feed_id=' + data.feed_id;
            },
            null,
            null,
            null
        )
    }




    const clickNextButton = () => {
        if( steps[creationStep].condition === true ){
            const stepsKeys = Object.keys( steps ),
                  nextStepKey = stepsKeys.indexOf( creationStep ) + 1;

            if( stepsKeys[nextStepKey] !== undefined ){
                setCreationStep( stepsKeys[nextStepKey] );
            }

            if( stepsKeys.length === nextStepKey ){
                submitNewFeed()
            }
        }
    }

    const clickPreviousButton = () => {
        const stepsKeys = Object.keys( steps ),
                prevStepKey = stepsKeys.indexOf( creationStep ) - 1;
        if( stepsKeys[prevStepKey] !== undefined ){
            setCreationStep( stepsKeys[prevStepKey] );
        }else{
            props.onBackClick()
        }
    }



    const printProcessOutput = () => {

        switch ( creationStep ) {
            case 'selectSource':
                return (
                    <SelectSourcesSection
                        selectedSources={ selectedSources }
                        selectSourcesAction={ ( sourceId ) => {
                            selectSourceClick( sourceId )
                        } }
                        context='creationprocess'
                    />
                )
            case 'selectTemplate':
                return (
                    <SelectTemplateSection
                        selectedTemplate={ selectedTemplate }
                        selectTemplateAction={ ( templateType ) => {
                            setSelectedTemplate( templateType )
                        } }
                    />
                )
                default :
                    return null;
        }
    }

    const selectSourceClick = ( sourceId ) => {
        const sSources = Object.values( selectedSources );
        if( !sSources.includes( sourceId ) ){
            sSources.push(sourceId)
        }else{
            sSources.splice( sSources.indexOf( sourceId ), 1 );
        }
        setSelectedSources(sSources)
    }

    const nextButtonOutput = ( customClass = '' ) => {
        let nxtBtnClasses = [
            'sb-creationprocess-btn sb-creationprocess-next-btn',
            customClass,
            steps[creationStep].condition === false ? 'sb-btn-disabled' : ''
        ];

        return (
            <Button
                text={ __( 'Next', 'sb-customizer' )    }
                size='medium'
                type='primary'
                icon='chevron-right'
                icon-position='right'
                iconSize='7'
                customClass={ nxtBtnClasses.join(' ') }
                onClick={ () => {
                    clickNextButton()
                }}
            />
        )
    }



    return (
        <>
            {
                submitFeed &&
                <FullScreenLoader/>
            }
            <div className='sb-creationprocess-ctn sb-fs sb-dark-text'>
                { nextButtonOutput( 'sb-creationprocess-next-top-btn' ) }
                <div className='sb-creationprocess-heading'>
                    <h4 className='sb-h4'>{ steps[creationStep].heading }</h4>
                    <span className='sb-text-small sb-dark2-text'>{ steps[creationStep].description }</span>
                </div>
                { printProcessOutput() }

            </div>
            <div className='sb-creationprocess-actions'>
                <Button
                    text={ __( 'Back', 'sb-customizer' )    }
                    size='medium'
                    type='secondary'
                    icon='chevron-left'
                    iconSize='7'
                    customClass='sb-creationprocess-btn sb-creationprocess-back-btn'
                    boxshadow={ false }
                    onClick={ () => {
                        clickPreviousButton()
                    } }
                />
                { nextButtonOutput() }
            </div>
        </>
)
}

export default CreationProcessScreen;