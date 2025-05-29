import { useContext, useMemo, useState } from 'react'
import { __ } from '@wordpress/i18n'
import ModalContainer from '../Global/ModalContainer';
import Button from './Button';
import Source from './Source';
import CustomizerSourcesModal from '../Modals/CustomizerSourcesModal';
import SbUtils from '../../Utils/SbUtils';

const FeedSources = ( props ) => {
    const {
        addSModal,
        freeRet,
        freeRetModal,
        bulkSr,
        apis
    } = useContext( SbUtils.getCurrentContext() );
    //const activeSources = props.sources.sourcesList.filter( source =>  props?.value.includes( source.account_id ) );
    const activeSources = props?.feedSourcesList || []

    const [ sourcesModalActive, setSourcesModalActive ] = useState( false );

    const removeSource = ( sourceId ) => {
        props?.value.splice( props?.value.indexOf(sourceId), 1 );
        props.onChange( props.value );
    }
    const connectFacebookActiveMemo = useMemo( () => {
        if( props.fbModal.connectFacebookActive === true ){
            setSourcesModalActive( true )
            return true;
        }
        return false;
    },[ props.fbModal ]);

    return(
        <>
            {
                activeSources.map( ( source, sourceInd ) => {
                    return (
                        <Source
                            customClass='sb-source-control-item'
                            key={ sourceInd }
                            provider={ source.provider }
                            checkbox={ false }
                            name={ source.name }
                            removeIcon={ true }
                            onRemoveClick={ () => {
                                removeSource( source.account_id )
                            }}
                        />
                    )
                } )
            }
            <Button
                customClass='sb-sources-chooser-btn'
                icon='plus'
                size='small'
                full-width={ true }
                type='secondary'
                onClick={ () => {
                    setSourcesModalActive( true )
                }}
                text={ __( 'Add another Source', 'sb-customizer' ) }
            />
            {
                (sourcesModalActive ||  ( props.fbManualModal?.connectFacebookManualActive !== undefined && props.fbManualModal?.connectFacebookManualActive === null ) ) &&
                <ModalContainer
                    size={SbUtils.getAddSourceModalSize(addSModal?.modalType === 'freeRetriever')}
                    closebutton={true}
                    onClose={ () => {
                        setSourcesModalActive( false )
                        props.fbManualModal.setConnectFacebookManualActive(false)
                    } }
                >
                    <CustomizerSourcesModal
                        sources={ props.sources }
                        selectedSources={ props?.value }
                        fbModal={ props.fbModal }
                        onCancel={ () => {
                            setSourcesModalActive( false )
                            props.fbManualModal.setConnectFacebookManualActive(false)
                        } }
                        onUpdateSources={ ( element ) => {
                            props.onChange( element )
                            setSourcesModalActive( false )
                            props.fbManualModal.setConnectFacebookManualActive(false)
                        } }
                    />
                </ModalContainer>
            }
        </>
    )
}
export default FeedSources;