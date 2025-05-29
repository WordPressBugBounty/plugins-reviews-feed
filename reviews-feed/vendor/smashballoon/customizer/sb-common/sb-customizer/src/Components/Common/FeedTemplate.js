import { useState } from 'react'
import { __ } from '@wordpress/i18n'
import ModalContainer from '../Global/ModalContainer';
import Notice from '../Global/Notice';
import CustomizerTemplateModal from '../Modals/CustomizerTemplateModal';
import Button from './Button';
import Template from './Template';

const FeedTemplate = ( props ) => {

    const activeTemplate = props.templatesList.filter( template =>  template.type === props?.value);

    const [ templateModalActive, setTemplateModalActive ] = useState( false );

    return (
        <>
            {
                ( props?.value && activeTemplate[0] !== undefined ) &&
                <Template
                    customClass='sb-templatecustomizer-item'
                    type={ activeTemplate[0].type }
                    title={ activeTemplate[0].title }
                    onClick={ () => {
                        setTemplateModalActive( true )
                    }}
                />
            }
            <Button
                customClass='sb-template-chooser-btn'
                icon='pen'
                size='small'
                full-width={ true }
                type='secondary'
                onClick={ () => {
                    setTemplateModalActive( true )
                }}
                text={ __( 'Change', 'sb-customizer' ) }
            />
            <Notice
                icon='info'
                text={ __( 'Changing a template might override your customizations', 'sb-customizer' ) }
            />
            {
                templateModalActive &&
                <ModalContainer
                    size='full'
                    closebutton={true}
                    onClose={ () => {
                        setTemplateModalActive( false )
                    } }
                >
                    <CustomizerTemplateModal
                        templatesList={ props.templatesList }
                        selectedTemplate={ props?.value }
                        onCancel={ () => {
                            setTemplateModalActive( false )
                        } }
                        onUpdateTemplate={ ( element ) => {
                            props.onChange( element )
                            setTemplateModalActive( false )
                        } }
                    />
                </ModalContainer>
            }
        </>
    )

}

export default FeedTemplate;