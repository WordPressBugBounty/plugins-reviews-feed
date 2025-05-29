import { useState, useContext, useRef } from "react"
import FeedEditorContext from '../../Context/FeedEditorContext'
import SbUtils from '../../../Utils/SbUtils'
import Checkbox from "../../Common/Checkbox"
import { ReactSortable } from "react-sortablejs"


const CheckBoxSection = ( { checkBoxSection, checkBoxSectionInd, editorBreadCrumb, parentSection } ) => {

    const {
        editorActiveSection,
        editorFeedSettings,
        sources,
        sbCustomizer,
        editorTopLoader,
        editorNotification,
        editorFeedData,
        upsellModal
    } = useContext( FeedEditorContext );

    const settingsRef = useRef( editorFeedSettings.feedSettings )


    const enableSectionElement = ( sectionID ) => {
        const sectionIncluded = editorFeedSettings?.feedSettings[checkBoxSection.settingId].includes( sectionID );
        if( sectionIncluded ){
            editorFeedSettings?.feedSettings[checkBoxSection.settingId].splice(editorFeedSettings?.feedSettings[checkBoxSection.settingId].indexOf(sectionID), 1);
        }else{
            editorFeedSettings?.feedSettings[checkBoxSection.settingId].push(sectionID);
        }
        editorFeedSettings.setFeedSettings(  {
            ...editorFeedSettings.feedSettings,
            [ checkBoxSection.settingId ] : editorFeedSettings?.feedSettings[checkBoxSection.settingId]
        } );

        if( checkBoxSection?.ajaxAction !== undefined ){
            switch ( checkBoxSection?.ajaxAction ) {
                case 'feedFlyPreview':
                    setTimeout(() => {
                        SbUtils.feedFlyPreview( editorFeedData, editorTopLoader, editorNotification, sbCustomizer, settingsRef )
                    }, 50);
                break;
                default:
                break;
            }
        }
    }

    if( checkBoxSection?.enableSorting ){
        let includedElements = editorFeedSettings?.feedSettings[checkBoxSection.settingId],
            includedElementsArray = checkBoxSection.controls.filter( el  => includedElements.includes( el.id ) ),
            excludedElementsArray = checkBoxSection.controls.filter( el  => !includedElements.includes( el.id ) );


        includedElementsArray.sort( ( a, b ) => {
            if(
                includedElements.indexOf(a.id) >
                includedElements.indexOf(b.id) ){
                    return 1
            }
            if(
                includedElements.indexOf(a.id) <
                includedElements.indexOf(b.id) ){
                    return -1
            }
            return 0;
        } );

        checkBoxSection.controls = includedElementsArray.concat(excludedElementsArray);
    }


    const changeOnSort = ( newArray ) => {
        editorFeedSettings.setFeedSettings(  {
            ...editorFeedSettings.feedSettings,
            [ checkBoxSection.settingId ] : newArray.reduce((ids, elem) => {
                if( editorFeedSettings?.feedSettings[checkBoxSection.settingId].includes( elem.id  ) ){
                    ids.push( elem.id )
                }
                return ids;
            }, [])
        } );
    }

    const navigateToSection = ( event, elem ) => {
        if( event.target === event.currentTarget && elem?.controls.length > 0  ){
            editorActiveSection.setActiveSection( elem )
            if( parentSection !== undefined ){
                const breadCrumbSection = editorBreadCrumb.breadCrumb !== null ? editorBreadCrumb.breadCrumb.concat( parentSection ) : [ parentSection ];
                editorBreadCrumb.setBreadCrumb( breadCrumbSection )
            }
        }
    }

    return (
        <div className='sb-checkboxsection-ctn sb-fs'>
            {
                checkBoxSection?.includeTop &&
                <div className='sb-checkboxsection-top sb-fs'>
                    { SbUtils.printIcon( 'eye', 'sb-checkboxsection-top-icon' ) }
                    <span className='sb-checkboxsection-top-label'>{ checkBoxSection.topLabel }</span>
                </div>
            }
            <ReactSortable
                disabled={!checkBoxSection?.enableSorting}
                list={checkBoxSection.controls}
                handle='.sb-checkboxsection-sorticon'
                setList={ ( newState ) => {
                        checkBoxSection.controls = newState
                    }
                }
                onEnd={
                    (ev) => {
                        const elementId = checkBoxSection.controls[ev.newIndex].id;
                        if( !editorFeedSettings?.feedSettings[checkBoxSection.settingId].includes( elementId ) ){
                            enableSectionElement( elementId )
                        }
                        changeOnSort( checkBoxSection.controls )
                    }
                }
                className='sb-checkboxsection-elements sb-fs'
            >
                {
                    checkBoxSection.controls.map( ( elem, elemInd ) => {
                        elem.controls = elem?.controls !== undefined ? elem?.controls : [];
                        const isSettingPro = SbUtils.checkSettingIsPro( elem.upsellModal );

                        return (
                            <div
                                className='sb-checkboxsection-elem sb-fs sb-tr-2'
                                data-haschildren={ elem?.controls.length > 0 }
                                key={ elemInd }
                                data-disabled={ isSettingPro !== false }
                                onClick={ ( event ) => {
                                    if( isSettingPro === false ) {
                                        navigateToSection( event, elem )
                                    }else{
                                        SbUtils.openUpsellModal( isSettingPro, upsellModal )
                                    }
                                }}
                            >
                            {
                                checkBoxSection?.disableCheckbox !== false &&
                                <Checkbox
                                    customClass='sb-checkbox-control'
                                    value={ editorFeedSettings?.feedSettings[checkBoxSection.settingId].includes(elem.id) }
                                    enabled={ true }
                                    disabled={ isSettingPro !== false }
                                    onChange={ ( event ) => {
                                        if( isSettingPro === false ) {
                                            enableSectionElement( elem.id )
                                        }
                                    } }
                                />
                            }
                            <span
                                className='sb-checkboxsection-label sb-text-tiny sb-bold'
                                onClick={ ( event ) => {
                                        if( isSettingPro === false ) {
                                            navigateToSection( event, elem )
                                        }
                                    }}
                                >
                                    { SbUtils.printIcon( elem?.icon, 'sb-checkboxsection-icon' ) }
                                    { elem.heading }
                                    { SbUtils.printProLabel( isSettingPro && elem?.disableProLabel !== true ) }
                            </span>
                                {
                                    checkBoxSection?.enableSorting &&
                                    SbUtils.printIcon( 'sort', 'sb-checkboxsection-sorticon sb-tr-2' )
                                }
                            </div>
                        )
                    })
                }
            </ReactSortable>
        </div>
    )

}

export default CheckBoxSection;